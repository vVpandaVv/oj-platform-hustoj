<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
OjPrimm\Core\Autoloader::register();
require_once __DIR__ . '/Support/SqlWpdb.php'; require_once __DIR__ . '/Support/ReleaseFixture.php';
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

use OjPrimm\Application\PracticeExecutor;
use OjPrimm\Application\PracticeService;
use OjPrimm\Application\PrimmService;
use OjPrimm\Storage\WpdbManagementStore;
use OjPrimm\Storage\WpdbPracticeStore;
use OjPrimm\Storage\WpdbRepository;
use OjPrimmTests\Platform;
use OjPrimmTests\SqlWpdb;

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void { ++$checks; if (! $ok) { throw new RuntimeException($label); } };
$reject = static function (callable $work, string $label) use ($assert): void { try { $work(); } catch (DomainException) { $assert(true, $label); return; } $assert(false, $label); };
$db = SqlWpdb::create(); $records = new WpdbRepository($db, true); $runs = new WpdbPracticeStore($db); $versions = new WpdbManagementStore($db, $records);
$platform = new Platform(); $lesson = OjPrimmTests\lesson(); $activity = $versions->version(null, OjPrimmTests\activity($lesson), 'practice-source');
$executor = new class implements PracticeExecutor {
    public bool $pending = false; public bool $fail = false; public int $submissions = 0; public array $tickets = []; public mixed $hook = null;
    public function execute(int $actor, array $material, string $source, int $caseIndex, string $key, ?string $ticket): array {
        if ($this->fail) { throw new DomainException('채점 서버 일시 오류'); }
        if ($ticket === null) { ++$this->submissions; $ticket = hash('sha256', $key); $this->tickets[$ticket] = true; }
        if (is_callable($this->hook)) { ($this->hook)(); }
        if ($this->pending) { return ['ticket' => $ticket, 'status' => 'pending']; }
        $parts = array_map('intval', explode(' ', trim($material['tests'][$caseIndex]['input'])));
        return ['ticket' => $ticket, 'status' => 'completed', 'verdict' => 'executed', 'actual_output' => (str_contains($source, 'WRONG') ? 0 : array_sum($parts)) . "\n", 'diagnostics' => ''];
    }
};
$practice = new PracticeService($records, $runs, $platform, $executor); $service = new PrimmService($records, $platform, $versions, $runs);
$platform->actor = 20; $source = $lesson['stages'][3]['starter_code'];
$input = ['source' => $source, 'request_key' => 'practice-key-0001'];
$reject(fn () => $practice->run($activity['id'], $input), 'Predict cannot run future Modify code.');
foreach (['predict', 'run', 'investigate'] as $version => $stage) { $service->progress($activity['id'], ['stage' => $stage, 'expected_version' => $version, 'responses' => [$stage => '생각'], 'advance' => true]); }
$reject(fn () => $service->progress($activity['id'], ['stage' => 'modify', 'expected_version' => 3, 'responses' => ['modify' => '수정'], 'draft_code' => $source, 'advance' => true]), 'Required Modify execution cannot be skipped with a text response.');
$executor->pending = true; $pending = $practice->run($activity['id'], $input); $input['ticket'] = $pending['ticket'];
$assert($pending['status'] === 'pending' && ! isset($pending['source'], $pending['executions']), 'Learner receives only a local job ID, never native tickets or reference source.');
$practice->run($activity['id'], $input);
$assert($executor->submissions === 1, 'Repeated polling uses the persisted native execution.');
$executor->pending = false;
do { $done = $practice->run($activity['id'], $input); } while ($done['status'] !== 'completed');
$assert($done['all_passed'] && count($done['results']) === 2 && $executor->submissions === 2, 'Every generated Modify case checks the learner program.');
$assert($records->findAttempt($activity['id'], 20)['version'] === 3, 'Practice execution does not race the progress version or create an official AC.');
$assert(! $service->activity($activity['id'])['attempt']['practice_passed'], 'Passing another source does not certify an empty saved draft.');
$service->progress($activity['id'], ['stage' => 'modify', 'expected_version' => 3, 'responses' => ['modify' => '수정'], 'draft_code' => $source]);
$reloaded = $service->activity($activity['id']);
$assert($reloaded['attempt']['practice_passed'] && $reloaded['attempt']['version'] === 4 && $reloaded['attempt']['practice_source_hash'] === $done['source_hash'], 'Reload derives current draft execution evidence without writing progress again.');
$other = array_replace($input, ['source' => $source . '# changed']);
$reject(fn () => $practice->run($activity['id'], $other), 'A changed source cannot reuse a previous job result.');
$platform->actor = 21;
foreach (['predict', 'run', 'investigate'] as $version => $stage) { $service->progress($activity['id'], ['stage' => $stage, 'expected_version' => $version, 'responses' => [$stage => '생각'], 'advance' => true]); }
$reject(fn () => $practice->run($activity['id'], $input), 'Another learner cannot reuse the local execution ID.');
$platform->actor = 20;
$reject(fn () => $service->progress($activity['id'], ['stage' => 'modify', 'expected_version' => 4, 'responses' => ['modify' => '수정'], 'draft_code' => $source . '# changed', 'advance' => true]), 'Passing a different source does not authorize current-draft advancement.');
$view = $service->progress($activity['id'], ['stage' => 'modify', 'expected_version' => 4, 'responses' => ['modify' => '수정'], 'draft_code' => $source, 'advance' => true]);
$assert($view['attempt']['current_stage'] === 'make' && $view['attempt']['practice_passed'] && ! $view['attempt']['is_accepted'], 'Practice pass advances Modify without fabricating Make acceptance.');
$wrong = ['source' => 'WRONG', 'request_key' => 'practice-key-wrong'];
do { $bad = $practice->run($activity['id'], $wrong); $wrong['ticket'] = $bad['ticket']; } while ($bad['status'] !== 'completed');
$assert(! $bad['all_passed'] && $bad['results'][0]['verdict'] === 'wrong_answer', 'Wrong learner outputs remain feedback, not a pass.');
$outage = ['source' => $source, 'request_key' => 'practice-key-outage'];
$executor->fail = true; $reject(fn () => $practice->run($activity['id'], $outage), 'Infrastructure errors do not create correctness evidence.');
$executor->fail = false;
do { $resumed = $practice->run($activity['id'], $outage); $outage['ticket'] = $resumed['ticket']; } while ($resumed['status'] !== 'completed');
$assert($resumed['all_passed'], 'The same run can resume after a transient execution failure.');
$executor->hook = function () use ($platform): void { $platform->allowed = false; };
$reject(fn () => $practice->run($activity['id'], ['source' => $source, 'request_key' => 'practice-key-revoked']), 'Membership is checked after the execution boundary as well.');
$platform->allowed = true; $executor->hook = null;
$executor->hook = function () use ($platform): void { $platform->actor = 21; };
$reject(fn () => $practice->run($activity['id'], ['source' => $source, 'request_key' => 'practice-key-actor-change']), 'A user switch during execution never returns another learner result.');
$platform->actor = 20; $executor->hook = null;
$lease = str_repeat('a', 48); $lease2 = str_repeat('b', 48);
$r = $runs->createOrFind($activity['id'], 30, hash('sha256', 'lease'), ['source' => '', 'results' => []]);
$assert($runs->claim($r['id'], $lease) && ! $runs->claim($r['id'], $lease2), 'Native polling has one private worker at a time.');
$reject(fn () => $runs->save($r['id'], $lease2, [], 'completed'), 'A stale worker cannot publish practice success.');
for ($i = 0; $i < 3; ++$i) { $runs->createOrFind($activity['id'], 31, hash('sha256', 'cap-' . $i), []); }
$reject(fn () => $runs->createOrFind($activity['id'], 31, hash('sha256', 'cap-over'), []), 'Serialized learner admission enforces the pending cap.');
$assert($runs->createOrFind($activity['id'], 31, hash('sha256', 'cap-0'), [])['status'] === 'pending', 'Existing idempotent work remains readable at the cap.');
$db->update('test_oj_primm_practices', ['created_at' => gmdate('Y-m-d H:i:s', time() - 86401)], ['id' => $done['id']]);
$beforeCached = $executor->submissions;
$assert($practice->run($activity['id'], $input)['status'] === 'completed' && $executor->submissions === $beforeCached,
    'A cached completed practice result remains readable after 24 hours without resubmission.');
$executor->pending = true; $ttlInput = ['source' => $source, 'request_key' => 'practice-absolute-expiry'];
$ttlRun = $practice->run($activity['id'], $ttlInput); $ttlInput['ticket'] = $ttlRun['ticket'];
$db->update('test_oj_primm_practices', ['created_at' => gmdate('Y-m-d H:i:s', time() - 86401)], ['id' => $ttlRun['id']]);
$beforeExpired = $executor->submissions;
$reject(fn () => $practice->run($activity['id'], $ttlInput), 'An old pending practice cannot submit or poll again beyond the absolute 24-hour request lifetime.');
$assert($practice->latest($activity['id'])['expired'] && $executor->submissions === $beforeExpired,
    'Reload exposes expiration without changing progress or creating another native execution.');
$executor->pending = false;
$db->insert('test_oj_primm_generation_control', ['scope_key' => 'queue', 'data_json' => json_encode(['date' => '2026-10-25', 'ai_calls' => 7, 'teachers' => ['20' => 2, '10' => 5]]), 'updated_at' => gmdate('Y-m-d H:i:s')]);
$export = $records->exportUser(20); $assert(count($export['practices']) >= 3 && $export['usage'][0]['ai_calls'] === 2 && ! str_contains(json_encode($export['usage']), 'teachers'), 'Privacy export includes private execution and only the individual teacher usage.');
$records->eraseUser(20); $assert($runs->latest($activity['id'], 20) === null && $records->findAttempt($activity['id'], 20) === null, 'Personal erasure removes practice records and progress.');
$control = json_decode($db->get_var("SELECT data_json FROM test_oj_primm_generation_control WHERE scope_key='queue'"), true);
$assert(! isset($control['teachers']['20']) && $control['teachers']['10'] === 5 && $control['ai_calls'] === 7, 'Personal erasure removes identified usage while preserving other users and aggregate limits.');
echo 'PRIMM learner practice checks passed: ' . $checks . " (production repositories, fixture OJ)\n";
