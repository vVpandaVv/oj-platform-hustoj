<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Jobs\Application\JobManager;
use CodingPanda\OjPlatform\Modules\Jobs\Application\IdempotencyKeyFactory;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobWorker;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWakeup;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueueReadiness;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobWakeup;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;

require __DIR__ . '/jobs.php';
$before = $assertions;
define('DISABLE_WP_CRON', true);
$GLOBALS['wake_http'] = [];
$GLOBALS['wake_http_error'] = false;
$GLOBALS['wake_cron'] = time() + 60;
function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
function wp_remote_post(string $url, array $args): mixed {
    $GLOBALS['wake_http'][] = [$url, $args];
    return $GLOBALS['wake_http_error'] ? (object) ['error' => true] : ['response' => ['code' => false]];
}
function is_wp_error(mixed $result): bool { return is_object($result); }
function wp_next_scheduled(string $hook): int|false { return $GLOBALS['wake_cron'] ?? false; }
function wp_schedule_single_event(int $time, string $hook): bool { $GLOBALS['wake_cron'] = $time; return true; }
function status_header(int $status): void { $GLOBALS['wake_status'] = $status; }

final class WakeDatabase {
    public string $options = 'custom_options';
    public string $last_error = '';
    public ?string $value = null;
    public bool $busy = false;
    public bool $writeFailure = false;
    public array $sql = [];
    public function prepare(string $sql, mixed ...$args): string { return json_encode([$sql, $args], JSON_THROW_ON_ERROR); }
    public function get_var(string $query): mixed {
        [$sql, $args] = json_decode($query, true, 512, JSON_THROW_ON_ERROR);
        $this->sql[] = $sql;
        if (str_contains($sql, 'SELECT option_value')) { return $this->value; }
        if (str_contains($args[0], '_signal_')) { return '1'; }
        if (str_contains($sql, 'IS_FREE_LOCK')) { return $this->busy ? '0' : '1'; }
        if (str_contains($sql, 'GET_LOCK')) {
            if ($this->busy) { return '0'; }
            $this->busy = true;
            return '1';
        }
        if (str_contains($sql, 'RELEASE_LOCK')) { $this->busy = false; return '1'; }
        throw new RuntimeException('Unexpected SQL');
    }
    public function query(string $query): int|false {
        [$sql, $args] = json_decode($query, true, 512, JSON_THROW_ON_ERROR);
        $this->sql[] = $sql;
        if ($this->writeFailure) { return false; }
        if (str_starts_with($sql, 'INSERT')) { $this->value = $args[1]; return 1; }
        if ($this->value !== $args[1]) { return 0; }
        $this->value = null;
        return 1;
    }
}

$db = new WakeDatabase();
$errors = new JobsErrorReporter();
$wake = new WordPressJobWakeup($db, $errors);
for ($i = 0; $i < 100; ++$i) { $wake->wake(); }
jobsSame(1, count($GLOBALS['wake_http']), 'Concurrent enqueue signals must coalesce, even with future cron and disabled WP-Cron.');
[$url, $request] = $GLOBALS['wake_http'][0];
jobsSame('https://example.test/wp-admin/admin-post.php', $url, 'Only the site worker endpoint receives a signal.');
jobsSame(false, $request['blocking'], 'Enqueue must not await AI.');
jobsSame(true, $request['sslverify'], 'TLS validation must stay enabled.');
jobsSame(0, $request['redirection'], 'Worker secrets must not follow redirects.');
jobsSame(['action', 'ticket'], array_keys($request['body']), 'No job payload or provider secrets in the wake request.');
$ticket = $request['body']['ticket'];
jobsAssert(! str_contains((string) $db->value, $ticket), 'Only a ticket hash is stored.');
jobsSame(false, $wake->consume('wrong'), 'Reject malformed tickets.');
jobsSame(false, $wake->consume(str_repeat('f', 64)), 'Reject a forged ticket.');
jobsSame(true, $wake->consume($ticket), 'Accept the issued ticket.');
jobsSame(false, $wake->consume($ticket), 'Reject replay.');
jobsSame(true, $wake->acquire(), 'Worker acquires the queue.');
jobsSame(false, (new WordPressJobWakeup($db, $errors))->acquire(), 'Concurrent cron/loopback cannot run a second worker.');
$wake->wake();
jobsSame(1, count($GLOBALS['wake_http']), 'An active worker drains the queue without extra HTTP requests.');
$wake->release();
$wake->wake();
$ticket = $GLOBALS['wake_http'][1][1]['body']['ticket'];
$db->value = json_encode(['hash' => hash('sha256', $ticket), 'expires' => time() - 1]);
jobsSame(false, $wake->consume($ticket), 'Expired ticket cannot start a worker.');
$GLOBALS['wake_http_error'] = true;
$wake->wake();
jobsSame(null, $db->value, 'Transport error clears only its pending signal, allowing retry.');
$GLOBALS['wake_http_error'] = false;
$db->writeFailure = true;
$httpCount = count($GLOBALS['wake_http']);
$wake->wake();
jobsSame($httpCount, count($GLOBALS['wake_http']), 'Storage failure cannot dispatch an unverifiable request.');
$db->writeFailure = false;

$vault = new MemoryJobVault();
$queue = new MemoryJobQueue();
$signal = new class($queue) implements JobWakeup {
    public int $calls = 0;
    public function __construct(private MemoryJobQueue $queue) {}
    public function wake(): void {
        jobsAssert($this->queue->plan !== null, 'Signal occurs only after queue commit.');
        ++$this->calls;
        throw new RuntimeException('Dispatch unavailable');
    }
};
$manager = new JobManager($definitions, new IdempotencyKeyFactory(), $vault, $queue, $signal);
$result = $manager->enqueue('problem_metadata_generate', 'selected', 17,
    [new JobTarget('problem', '1099', ['problem_id' => 1099])], [], [], 'problems.metadata.generate');
jobsSame(41, $result->jobId(), 'A failed signal must not report the committed enqueue as failed.');
jobsSame(1, count($vault->values), 'A failed signal must preserve the job input.');
jobsSame(1, $signal->calls, 'One signal per enqueue.');

$ready = new class implements JobQueueReadiness {
    public bool $ready = true;
    public int $checks = 0;
    public function hasReadyItems(): bool { ++$this->checks; return $this->ready; }
};
$handlers = new JobHandlerRegistry();
$handlers->register(new MemoryJobHandler());
$worker = new JobWorker($queue, $handlers, $definitions, $operations, $vault, new MemoryJobResultJournal(), $errors);
$scheduler = new WordPressJobScheduler($worker, $vault, $errors, $wake, $ready);
$GLOBALS['wake_cron'] = false;
$scheduler->run();
jobsSame(1, $queue->finishCalls, 'The cron fallback uses the same worker.');
jobsSame(1, $ready->checks, 'Ready work is checked after releasing the lock.');
jobsSame(false, $db->busy, 'Batch releases the worker lock.');
jobsAssert($GLOBALS['wake_cron'] >= time() + 59, 'Recovery schedule is retained.');
jobsAssert($db->value !== null, 'More work gets a new immediate signal instead of waiting for cron.');
$ticket = $GLOBALS['wake_http'][array_key_last($GLOBALS['wake_http'])][1]['body']['ticket'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST['ticket'] = $ticket;
$scheduler->handleWakeup();
jobsSame(403, $GLOBALS['wake_status'], 'GET cannot start worker even with a valid token.');
$ready->ready = false;
$_SERVER['REQUEST_METHOD'] = 'POST';
$scheduler->handleWakeup();
jobsSame(204, $GLOBALS['wake_status'], 'Authenticated loopback runs without a user login.');
$count = $ready->checks;
$scheduler->handleWakeup();
jobsSame(403, $GLOBALS['wake_status'], 'Endpoint rejects token replay.');
jobsSame($count, $ready->checks, 'Rejected requests perform no queue work.');

$queue->throwOnFinish = true;
$queue->allowClaim();
$ready->ready = true;
$scheduler->run();
jobsSame($count, $ready->checks, 'Fatal persistence errors wait for recovery instead of busy-dispatching.');
jobsSame(false, $db->busy, 'Error paths release the worker lock.');
fwrite(STDOUT, 'Immediate jobs dispatch: ' . ($assertions - $before) . " assertions passed.\n");
