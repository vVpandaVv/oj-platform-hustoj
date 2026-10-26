<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\JobHandlerContractValidator;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Application\IdempotencyKeyFactory;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessPolicy;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobControlService;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobManager;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobWorker;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobControl;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobResultJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\PrivateDataRootProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraft;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobControlResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobExecutionPlan;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemPlan;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobStatus;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\Filesystem\EncryptedFileFormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobQueue;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobResultJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress\WordPressJobScheduler;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Contract\PayloadCipher;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$assertions = 0;

function jobsAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function jobsSame(mixed $expected, mixed $actual, string $message): void
{
    jobsAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

final class JobsSystemOperationPolicy implements SystemOperationPolicy
{
    public function maintenanceEnabled(): bool
    {
        return false;
    }

    public function maintenanceMessage(): string
    {
        return '';
    }

    public function maintenanceEndsAt(): ?string
    {
        return null;
    }

    public function isModuleWriteFrozen(string $moduleKey): bool
    {
        unset($moduleKey);
        return false;
    }

    public function allowsAction(string $moduleKey, string $actionKey): bool
    {
        unset($moduleKey, $actionKey);
        return true;
    }

    public function allowsJob(string $moduleKey, string $jobKey): bool
    {
        unset($moduleKey, $jobKey);
        return true;
    }
}

final class MemoryJobVault implements FormDraftStore
{
    /**
     * @var array<string, FormDraft>
     */
    public array $values = [];

    public bool $throwOnDiscard = false;

    public int $purgeCalls = 0;

    private int $sequence = 1;

    public function store(
        string $draftType,
        int $ownerUserId,
        string $targetType,
        string $targetKey,
        array $payload,
        int $ttlSeconds = 86400
    ): FormDraftReference {
        $suffix = str_pad(dechex($this->sequence++), 12, '0', STR_PAD_LEFT);
        $reference = new FormDraftReference(
            'draft:v1:00000000-0000-4000-8000-' . $suffix
        );
        $createdAt = time();
        $this->values[$reference->value()] = new FormDraft(
            $reference,
            $draftType,
            $ownerUserId,
            $targetType,
            $targetKey,
            $payload,
            $createdAt,
            $createdAt + $ttlSeconds
        );

        return $reference;
    }

    public function find(
        FormDraftReference $reference,
        int $ownerUserId
    ): ?FormDraft
    {
        $draft = $this->values[$reference->value()] ?? null;

        return $draft?->ownerUserId() === $ownerUserId ? $draft : null;
    }

    public function discard(
        FormDraftReference $reference,
        int $ownerUserId
    ): void {
        if ($this->throwOnDiscard) {
            throw new RuntimeException('테스트 비밀값 폐기 실패');
        }
        if ($this->find($reference, $ownerUserId) !== null) {
            unset($this->values[$reference->value()]);
        }
    }

    public function purgeExpired(int $limit = 100): int
    {
        unset($limit);
        ++$this->purgeCalls;

        return 0;
    }
}

final class MemoryJobQueue implements JobQueue
{
    public ?JobExecutionPlan $plan = null;

    /**
     * @var list<JobItemPlan>
     */
    public array $items = [];

    /**
     * @var list<JobHandlerResult>
     */
    public array $results = [];

    public bool $created = true;

    public bool $throwOnFinish = false;

    public int $finishCalls = 0;

    private bool $claimed = false;

    public function enqueue(
        JobExecutionPlan $plan,
        array $items
    ): JobEnqueueResult {
        $this->plan = $plan;
        $this->items = $items;
        $this->claimed = false;

        return new JobEnqueueResult(41, $this->created);
    }

    public function claim(
        string $workerId,
        int $leaseSeconds
    ): ?ClaimedJobItem {
        unset($workerId, $leaseSeconds);
        if ($this->claimed || $this->plan === null || $this->items === []) {
            return null;
        }
        $this->claimed = true;
        $item = $this->items[0];

        return new ClaimedJobItem(
            41,
            71,
            $this->plan->jobType(),
            $item->targetType(),
            $item->targetKey(),
            $item->targetVersion(),
            $item->targetUpdatedAt(),
            $item->inputHash(),
            $item->payloadReference(),
            1,
            $item->maxAttempts(),
            $this->plan->createdByUserId(),
            str_repeat('a', 64)
        );
    }

    public function finish(
        ClaimedJobItem $claim,
        JobHandlerResult $result
    ): void {
        unset($claim);
        ++$this->finishCalls;
        if ($this->throwOnFinish) {
            throw new RuntimeException('주입한 Jobs 결과 저장 오류');
        }
        $this->results[] = $result;
    }

    public function allowClaim(): void
    {
        $this->claimed = false;
    }
}

final class MemoryJobHandler implements JobHandler
{
    public mixed $handledPayload = null;

    public bool $throw = false;

    public int $handleCalls = 0;

    public function jobType(): string
    {
        return 'problem_metadata_generate';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        ++$this->handleCalls;
        if ($this->throw) {
            throw new RuntimeException('주입한 일시 오류');
        }
        $this->handledPayload = $item->payload();

        return JobHandlerResult::succeeded(
            'metadata_draft_ready',
            '메타데이터 초안을 만들었습니다.',
            ['difficulty_score' => 3]
        );
    }
}

final class MemoryJobResultJournal implements JobResultJournal
{
    /**
     * @var array<string, JobHandlerResult>
     */
    public array $results = [];

    public function find(ClaimedJobItem $claim): ?JobHandlerResult
    {
        return $this->results[$this->key($claim)] ?? null;
    }

    public function save(
        ClaimedJobItem $claim,
        JobHandlerResult $result
    ): void {
        if ($result->outcome() !== 'succeeded') {
            throw new RuntimeException('성공 결과만 저장할 수 있습니다.');
        }
        $this->results[$this->key($claim)] = $result;
    }

    private function key(ClaimedJobItem $claim): string
    {
        return $claim->jobId() . ':' . $claim->itemId()
            . ':' . $claim->inputHash();
    }
}

final class MemoryJobQuery implements JobQuery
{
    public function __construct(public ?JobDetails $job)
    {
    }

    public function find(int $jobId): ?JobDetails
    {
        return $this->job?->id() === $jobId ? $this->job : null;
    }
}

final class MemoryJobControl implements JobControl
{
    public ?JobControlResult $cancelResult = null;

    public ?JobControlResult $retryResult = null;

    public function requestCancellation(
        int $jobId,
        int $actorUserId
    ): JobControlResult {
        unset($jobId, $actorUserId);

        return $this->cancelResult ?? new JobControlResult(0);
    }

    public function retryFailed(
        int $jobId,
        int $actorUserId
    ): JobControlResult {
        unset($jobId, $actorUserId);

        return $this->retryResult ?? new JobControlResult(0);
    }
}

final class JobsCreatorAccessRule implements JobAccessRule
{
    public function policyKeys(): array
    {
        return ['problems.metadata.generate'];
    }

    public function policyVersion(): string
    {
        return '1';
    }

    public function canView(int $userId, JobAccessContext $job): bool
    {
        return $job->createdByUserId() === $userId;
    }

    public function canControl(int $userId, JobAccessContext $job): bool
    {
        return $this->canView($userId, $job);
    }
}

final class JobsErrorReporter implements ErrorReporter
{
    public int $reported = 0;

    public function report(Throwable $error): void
    {
        unset($error);
        ++$this->reported;
    }
}

final class JobsWpdb
{
    /**
     * @var list<array{sql:string,args:list<mixed>}>
     */
    public array $prepared = [];

    /**
     * @var list<string>
     */
    public array $getVarQueries = [];

    public int $insert_id = 1;

    public ?object $idempotencyRow = null;

    /**
     * @var array<string, object>
     */
    private array $resultRows = [];

    public function insert(string $table, array $data): bool
    {
        unset($table, $data);

        return true;
    }

    public function update(string $table, array $data, array $where): bool
    {
        unset($table, $data, $where);

        return true;
    }

    public function query(string $sql): int
    {
        $prepared = $this->preparedCall($sql);
        if (
            $prepared !== null
            && str_contains($prepared['sql'], 'INSERT INTO background_job_steps')
            && ($prepared['args'][2] ?? null) === 'worker.result'
        ) {
            $key = (int) $prepared['args'][0] . ':' . (int) $prepared['args'][1];
            $this->resultRows[$key] = (object) [
                'input_hash' => (string) ($prepared['args'][4] ?? ''),
                'checkpoint' => (string) ($prepared['args'][5] ?? ''),
            ];
        }

        return 1;
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        $this->prepared[] = ['sql' => $sql, 'args' => $args];

        return 'prepared-' . count($this->prepared);
    }

    public function get_var(string $sql): mixed
    {
        $this->getVarQueries[] = $sql;

        return null;
    }

    public function get_row(string $sql): mixed
    {
        $prepared = $this->preparedCall($sql);
        if (
            $prepared !== null
            && str_contains($prepared['sql'], 'FROM background_job_steps')
            && ($prepared['args'][2] ?? null) === 'worker.result'
        ) {
            $key = (int) $prepared['args'][0] . ':' . (int) $prepared['args'][1];

            return $this->resultRows[$key] ?? null;
        }
        if (
            $prepared !== null
            && str_contains($prepared['sql'], 'FROM background_jobs WHERE idempotency_key')
        ) {
            return $this->idempotencyRow;
        }

        return null;
    }

    /**
     * @return list<object>
     */
    public function get_results(string $sql): array
    {
        if (str_contains($sql, 'GROUP BY status')) {
            return [(object) ['status' => 'failed', 'total' => 1]];
        }

        return [];
    }

    /**
     * @return array{sql:string,args:list<mixed>}|null
     */
    private function preparedCall(string $token): ?array
    {
        if (preg_match('/^prepared-([1-9][0-9]*)$/', $token, $matches) !== 1) {
            return null;
        }

        return $this->prepared[(int) $matches[1] - 1] ?? null;
    }
}

final class JobsPlaintextCipher implements PayloadCipher
{
    public function encrypt(mixed $value, string $associatedData): array
    {
        return [
            'version' => 1,
            'associated_data' => $associatedData,
            'value' => $value,
        ];
    }

    public function decrypt(array $record, string $associatedData): mixed
    {
        if (
            ($record['associated_data'] ?? null) !== $associatedData
            || ! array_key_exists('value', $record)
        ) {
            throw new RuntimeException('테스트 암호화 연결 정보가 다릅니다.');
        }

        return $record['value'];
    }
}

final class JobsFixedPrivateRoot implements PrivateDataRootProvider
{
    public function __construct(private readonly string $path)
    {
    }

    public function root(): string
    {
        return $this->path;
    }
}

$definitions = new DefinitionRegistry('jobs-test', JobDefinition::class);
$definition = JobDefinition::fromArray([
    'key' => 'problem_metadata_generate',
    'owner_module' => 'problems',
    'definition_version' => '1',
    'max_attempts' => 3,
    'uses_steps' => false,
]);
$definitions->register($definition);
$operations = new JobsSystemOperationPolicy();
$vault = new MemoryJobVault();
$queue = new MemoryJobQueue();
$manager = new JobManager(
    $definitions,
    new IdempotencyKeyFactory(),
    $vault,
    $queue
);
$enqueue = $manager->enqueue(
    'problem_metadata_generate',
    'selected',
    17,
    [
        new JobTarget(
            'problem',
            '1024',
            ['problem_id' => 1024, 'input_hash' => str_repeat('b', 64)],
            str_repeat('c', 64),
            '2026-07-26 10:00:00'
        ),
    ],
    ['dictionary_version' => 'topic-v1'],
    ['problem_ids' => [1024]],
    'problems.metadata.generate'
);
jobsSame(41, $enqueue->jobId(), '등록한 Jobs 식별자가 올바르지 않습니다.');
jobsAssert($enqueue->wasCreated(), '첫 등록은 새 작업이어야 합니다.');
jobsSame('selected', $queue->plan?->executionMode(), '요청한 Jobs 실행 모드를 유지해야 합니다.');
jobsSame(1, count($queue->items), 'Jobs 대상을 항목으로 고정해야 합니다.');
jobsSame(1, count($vault->values), 'Jobs 입력을 암호화 저장소에 보관해야 합니다.');

$ownerQueueWpdb = new class {
    public int $insert_id = 0;
    public array $jobs = [];
    private array $prepared = [];
    public function prepare(string $sql, mixed ...$args): string
    {
        $this->prepared[] = ['sql' => $sql, 'args' => $args];
        return 'owner-job-sql-' . count($this->prepared);
    }
    public function get_row(string $token): ?object
    {
        $call = $this->prepared[(int) substr($token, strlen('owner-job-sql-')) - 1] ?? null;
        if ($call !== null && str_contains($call['sql'], 'WHERE idempotency_key = %s')) {
            $job = $this->jobs[$call['args'][0]] ?? null;
            return $job === null ? null : (object) $job;
        }
        return null;
    }
    public function insert(string $table, array $data): int
    {
        if ($table === 'background_jobs') {
            $this->insert_id = count($this->jobs) + 501;
            $this->jobs[$data['idempotency_key']] = ['id' => $this->insert_id] + $data;
        }
        return 1;
    }
    public function update(string $table, array $data, array $where): int
    {
        unset($table, $data, $where);
        return 1;
    }
    public function query(string $sql): int { unset($sql); return 1; }
    public function get_var(string $sql): mixed { unset($sql); return null; }
    public function get_results(string $sql): array { unset($sql); return []; }
};
$ownerVault = new MemoryJobVault();
$ownerManager = new JobManager($definitions, new IdempotencyKeyFactory(), $ownerVault, new WordPressJobQueue($ownerQueueWpdb));
$enqueueForOwner = static fn (int $actor): JobEnqueueResult => $ownerManager->enqueue(
    'problem_metadata_generate',
    'single',
    $actor,
    [new JobTarget('problem', '1024', ['problem_id' => 1024], str_repeat('c', 64))],
    ['dictionary_version' => 'topic-v1'],
    ['problem_ids' => [1024]],
    'problems.metadata.generate'
);
$firstOwnerJob = $enqueueForOwner(17);
$secondOwnerJob = $enqueueForOwner(23);
jobsAssert($secondOwnerJob->wasCreated(), '다른 계정의 동일 입력은 기존 계정의 작업을 재사용하면 안 됩니다.');
jobsAssert($firstOwnerJob->jobId() !== $secondOwnerJob->jobId(), '같은 문제·입력이라도 다른 계정에는 별도 작업 번호를 반환해야 합니다.');
jobsSame([17, 23], array_column(array_values($ownerQueueWpdb->jobs), 'created_by_user_id'), '작업별 원래 실행자와 권한 경계를 유지해야 합니다.');
$repeatedOwnerJob = $enqueueForOwner(23);
jobsAssert(! $repeatedOwnerJob->wasCreated(), '같은 계정의 동일 입력 중복 요청은 기존 작업을 재사용해야 합니다.');
jobsSame($secondOwnerJob->jobId(), $repeatedOwnerJob->jobId(), '중복 요청에는 현재 계정의 작업 번호만 반환해야 합니다.');
jobsSame([17, 23], array_map(static fn (FormDraft $draft): int => $draft->ownerUserId(), array_values($ownerVault->values)), '계정별 원문 payload를 유지하고 같은 계정의 중복 payload만 폐기해야 합니다.');

$handlers = new JobHandlerRegistry();
$handler = new MemoryJobHandler();
$handlers->register($handler);
(new JobHandlerContractValidator(
    $definitions,
    $handlers,
    ['problems']
))->validate();
$missingHandlerRejected = false;
try {
    (new JobHandlerContractValidator(
        $definitions,
        new JobHandlerRegistry(),
        ['problems']
    ))->validate();
} catch (Throwable) {
    $missingHandlerRejected = true;
}
jobsAssert(
    $missingHandlerRejected,
    '완료 모듈의 Jobs 정의에 Handler가 없으면 부팅 전에 거부해야 합니다.'
);
$errors = new JobsErrorReporter();
$resultJournal = new MemoryJobResultJournal();
$worker = new JobWorker(
    $queue,
    $handlers,
    $definitions,
    $operations,
    $vault,
    $resultJournal,
    $errors
);
$worker->run();
jobsSame(
    ['problem_id' => 1024, 'input_hash' => str_repeat('b', 64)],
    $handler->handledPayload,
    'Worker가 암호화 입력을 Handler에 전달해야 합니다.'
);
jobsSame('succeeded', $queue->results[0]->outcome(), '성공 결과를 큐에 확정해야 합니다.');
jobsSame([], $vault->values, '성공한 Jobs 입력은 암호화 저장소에서 제거해야 합니다.');
jobsSame(0, $errors->reported, '정상 Jobs 실행에서 오류를 보고하면 안 됩니다.');
$checkpointVault = new MemoryJobVault();
$checkpointQueue = new MemoryJobQueue();
$checkpointManager = new JobManager(
    $definitions,
    new IdempotencyKeyFactory(),
    $checkpointVault,
    $checkpointQueue
);
$checkpointManager->enqueue(
    'problem_metadata_generate',
    'selected',
    17,
    [new JobTarget('problem', '4096', ['problem_id' => 4096])],
    [],
    ['problem_ids' => [4096]],
    'problems.metadata.generate'
);
$checkpointHandler = new MemoryJobHandler();
$checkpointHandlers = new JobHandlerRegistry();
$checkpointHandlers->register($checkpointHandler);
$checkpointResults = new MemoryJobResultJournal();
$checkpointErrors = new JobsErrorReporter();
$checkpointQueue->throwOnFinish = true;
$checkpointWorker = new JobWorker(
    $checkpointQueue,
    $checkpointHandlers,
    $definitions,
    $operations,
    $checkpointVault,
    $checkpointResults,
    $checkpointErrors
);
$checkpointWorker->run();
jobsSame(
    1,
    $checkpointHandler->handleCalls,
    '최종 상태 저장 실패 전 업무 Handler는 한 번 실행되어야 합니다.'
);
jobsSame(
    1,
    count($checkpointResults->results),
    '성공 결과를 최종 상태 저장 전에 체크포인트로 보존해야 합니다.'
);
jobsSame(
    1,
    count($checkpointVault->values),
    '최종 상태 저장 실패 시 작업 입력을 유지해야 합니다.'
);
$checkpointQueue->throwOnFinish = false;
$checkpointQueue->allowClaim();
$checkpointWorker->run();
jobsSame(
    1,
    $checkpointHandler->handleCalls,
    '재임대 Worker는 체크포인트가 있는 업무 Handler를 다시 실행하면 안 됩니다.'
);
jobsSame(
    'succeeded',
    $checkpointQueue->results[0]->outcome(),
    '재임대 Worker는 체크포인트 결과로 작업을 완료해야 합니다.'
);
jobsSame(
    [],
    $checkpointVault->values,
    '체크포인트 결과가 최종 확정되면 작업 입력을 폐기해야 합니다.'
);
$maintenanceDrafts = new MemoryJobVault();
(new WordPressJobScheduler(
    new JobWorker(
        new MemoryJobQueue(),
        new JobHandlerRegistry(),
        $definitions,
        $operations,
        $maintenanceDrafts,
        new MemoryJobResultJournal(),
        new JobsErrorReporter()
    ),
    $maintenanceDrafts,
    new JobsErrorReporter()
))->run();
jobsSame(
    1,
    $maintenanceDrafts->purgeCalls,
    'Jobs 예약 실행마다 만료된 암호화 입력을 정리해야 합니다.'
);

$duplicateVault = new MemoryJobVault();
$duplicateQueue = new MemoryJobQueue();
$duplicateQueue->created = false;
$duplicateManager = new JobManager(
    $definitions,
    new IdempotencyKeyFactory(),
    $duplicateVault,
    $duplicateQueue
);
$duplicate = $duplicateManager->enqueue(
    'problem_metadata_generate',
    'selected',
    17,
    [new JobTarget('problem', '1024', ['problem_id' => 1024])],
    [],
    ['problem_ids' => [1024]],
    'problems.metadata.generate'
);
jobsAssert(! $duplicate->wasCreated(), '멱등 충돌은 기존 작업을 반환해야 합니다.');
jobsSame([], $duplicateVault->values, '중복 작업의 새 암호화 입력을 폐기해야 합니다.');

$retryVault = new MemoryJobVault();
$retryQueue = new MemoryJobQueue();
$retryManager = new JobManager(
    $definitions,
    new IdempotencyKeyFactory(),
    $retryVault,
    $retryQueue
);
$retryManager->enqueue(
    'problem_metadata_generate',
    'selected',
    17,
    [new JobTarget('problem', '2048', ['problem_id' => 2048])],
    [],
    ['problem_ids' => [2048]],
    'problems.metadata.generate'
);
$retryHandler = new MemoryJobHandler();
$retryHandler->throw = true;
$retryHandlers = new JobHandlerRegistry();
$retryHandlers->register($retryHandler);
$retryErrors = new JobsErrorReporter();
(new JobWorker(
    $retryQueue,
    $retryHandlers,
    $definitions,
    $operations,
    $retryVault,
    new MemoryJobResultJournal(),
    $retryErrors
))->run();
jobsSame('retry', $retryQueue->results[0]->outcome(), '일시 오류는 최대 시도 전 재시도해야 합니다.');
jobsSame(1, count($retryVault->values), '재시도 입력은 암호화 저장소에 유지해야 합니다.');
jobsSame(1, $retryErrors->reported, 'Handler 예외를 오류 경계에 보고해야 합니다.');

$wpdb = new JobsWpdb();
$wordpressQueue = new WordPressJobQueue($wpdb);
$maxAttemptClaim = new ClaimedJobItem(
    51,
    81,
    'problem_number_change',
    'problem_number_mapping',
    'mapping:test',
    null,
    null,
    str_repeat('d', 64),
    new FormDraftReference('draft:v1:00000000-0000-4000-8000-000000000099'),
    1,
    1,
    17,
    str_repeat('e', 64)
);
$journalDatabase = new JobsWpdb();
$wordpressResultJournal = new WordPressJobResultJournal($journalDatabase);
$journalResult = JobHandlerResult::succeeded(
    'problem.number_changed',
    '문제 번호를 변경했습니다.',
    ['mapping_count' => 2]
);
$wordpressResultJournal->save($maxAttemptClaim, $journalResult);
jobsSame(
    $journalResult->toArray(),
    $wordpressResultJournal->find($maxAttemptClaim)?->toArray(),
    'WordPress 성공 결과 체크포인트는 입력 해시와 안전 결과를 복원해야 합니다.'
);
$wordpressQueue->finish(
    $maxAttemptClaim,
    JobHandlerResult::retry('retry_requested', '다시 시도합니다.', 30)
);
jobsSame(
    'failed',
    $wpdb->prepared[0]['args'][0] ?? null,
    '최대 실행 횟수에 도달한 재시도 요청은 실패로 확정해야 합니다.'
);
jobsSame(
    'retry_requested',
    $wpdb->prepared[0]['args'][1] ?? null,
    '최대 실행 횟수에 도달해도 마지막 오류 코드는 보존해야 합니다.'
);
jobsSame('다시 시도합니다.', $wpdb->prepared[0]['args'][2] ?? null, '마지막 오류 메시지를 보존해야 합니다.');
jobsSame(
    ['retry_exhausted' => true, 'attempt_count' => 1],
    json_decode($wpdb->prepared[0]['args'][3] ?? '{}', true),
    '재시도 종료 정보는 원인과 별도로 저장해야 합니다.'
);

$claimWpdb = new JobsWpdb();
$claimQueue = new WordPressJobQueue($claimWpdb);
jobsSame(null, $claimQueue->claim('wordpress-test', 60), '실행 가능한 항목이 없으면 임대하지 않아야 합니다.');
jobsAssert(
    str_contains(
        $claimWpdb->getVarQueries[0] ?? '',
        'i.attempt_count < i.max_attempts'
    ),
    '최대 실행 횟수에 도달한 만료 항목을 다시 임대하면 안 됩니다.'
);

$retiredWpdb = new JobsWpdb();
$retiredWpdb->idempotencyRow = (object) ['id' => 77, 'status' => 'cancelled'];
$retiredQueue = new WordPressJobQueue($retiredWpdb);
$existingJobIdMethod = new ReflectionMethod($retiredQueue, 'existingJobId');
$existingJobIdMethod->setAccessible(true);
jobsSame(
    0,
    $existingJobIdMethod->invoke($retiredQueue, str_repeat('a', 64)),
    '종료된 작업이 차지한 멱등 키는 재사용을 막으면 안 됩니다.'
);
jobsAssert(
    str_contains($retiredWpdb->prepared[1]['sql'] ?? '', "SET idempotency_key = SHA2(CONCAT(idempotency_key, ':retired:', id), 256)")
        && ($retiredWpdb->prepared[1]['args'][0] ?? null) === 77,
    '종료된 작업의 멱등 키는 회수되어 새 작업 등록에 재사용될 수 있어야 합니다.'
);

$activeWpdb = new JobsWpdb();
$activeWpdb->idempotencyRow = (object) ['id' => 88, 'status' => 'queued'];
$activeQueue = new WordPressJobQueue($activeWpdb);
$activeExistingJobIdMethod = new ReflectionMethod($activeQueue, 'existingJobId');
$activeExistingJobIdMethod->setAccessible(true);
jobsSame(
    88,
    $activeExistingJobIdMethod->invoke($activeQueue, str_repeat('a', 64)),
    '대기·진행 중인 작업의 멱등 키는 그대로 중복 등록을 막아야 합니다.'
);
jobsSame(
    1,
    count($activeWpdb->prepared),
    '진행 중인 작업의 멱등 키는 회수하면 안 됩니다.'
);

$controlJob = new JobDetails(
    91,
    'problem_metadata_generate',
    'problems',
    'problems.metadata.generate',
    '1',
    17,
    'failed',
    2,
    1,
    1,
    0,
    '2026-07-27 00:00:00',
    '2026-07-27 00:01:00',
    [],
    [],
    [],
    []
);
$controlQuery = new MemoryJobQuery($controlJob);
$control = new MemoryJobControl();
$controlVault = new MemoryJobVault();
$cancelledReference = $controlVault->store(
    'problem_metadata_generate',
    17,
    'problem',
    '1024',
    ['problem_id' => 1024]
);
$control->cancelResult = new JobControlResult(
    1,
    [$cancelledReference->value()]
);
$control->retryResult = new JobControlResult(1);
$controlRules = new JobAccessRuleRegistry([
    new JobsCreatorAccessRule(),
]);
$controlAccess = new JobAccessPolicy($controlRules);
$controlErrors = new JobsErrorReporter();
$controlService = new JobControlService(
    $controlQuery,
    $control,
    $controlAccess,
    $controlVault,
    $controlErrors
);
jobsSame(
    1,
    $controlService->cancel(17, 91)->affectedCount(),
    '작업 등록자는 자기 작업을 취소할 수 있어야 합니다.'
);
jobsSame(
    [],
    $controlVault->values,
    '취소된 대기 항목의 암호화 입력은 함께 폐기해야 합니다.'
);
jobsSame(
    1,
    $controlService->retryFailed(17, 91)->affectedCount(),
    '작업 등록자는 실패한 자기 작업을 다시 시도할 수 있어야 합니다.'
);
$unauthorizedControl = false;
try {
    $controlService->cancel(18, 91);
} catch (DomainException) {
    $unauthorizedControl = true;
}
jobsAssert($unauthorizedControl, '다른 사용자는 작업을 취소할 수 없어야 합니다.');
jobsAssert(
    ! (new JobAccessPolicy(new JobAccessRuleRegistry([])))->canView(
        17,
        $controlJob
    ),
    '등록되지 않은 작업 접근 정책은 기본 거부해야 합니다.'
);
jobsSame(
    null,
    $controlRules->find('INVALID POLICY', '1'),
    '손상된 작업 접근 정책 식별자는 예외 없이 기본 거부해야 합니다.'
);
$discardFailureVault = new MemoryJobVault();
$discardFailureReference = $discardFailureVault->store(
    'problem_metadata_generate',
    17,
    'problem',
    '1024',
    ['problem_id' => 1024]
);
$discardFailureVault->throwOnDiscard = true;
$control->cancelResult = new JobControlResult(
    1,
    [$discardFailureReference->value()]
);
$discardFailureErrors = new JobsErrorReporter();
$discardFailureService = new JobControlService(
    $controlQuery,
    $control,
    $controlAccess,
    $discardFailureVault,
    $discardFailureErrors
);
jobsSame(
    1,
    $discardFailureService->cancel(17, 91)->affectedCount(),
    '취소 확정 뒤 비밀 입력 폐기 오류가 작업 결과를 뒤집으면 안 됩니다.'
);
jobsSame(
    1,
    $discardFailureErrors->reported,
    '비밀 입력 폐기 오류는 공통 오류 경계에 보고해야 합니다.'
);
jobsAssert(JobStatus::Failed->isTerminal(), '실패 상태는 폴링 종료 상태여야 합니다.');
jobsAssert(JobStatus::Partial->isTerminal(), '일부 완료 상태는 폴링 종료 상태여야 합니다.');

$draftRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'oj-platform-drafts-' . bin2hex(random_bytes(6));
$draftStore = new EncryptedFileFormDraftStore(
    new JobsFixedPrivateRoot($draftRoot),
    new JobsPlaintextCipher()
);
$draftReference = $draftStore->store(
    'problem_metadata',
    17,
    'problem',
    '1024',
    ['difficulty_score' => 3, 'topic_keys' => ['sorting']],
    3600
);
jobsAssert(
    $draftReference instanceof FormDraftReference,
    '암호화 Form Draft 참조를 발급해야 합니다.'
);
$draft = $draftStore->find($draftReference, 17);
jobsSame(
    ['difficulty_score' => 3, 'topic_keys' => ['sorting']],
    $draft?->payload(),
    'Form Draft 내용을 소유자에게 복원해야 합니다.'
);
jobsSame(
    null,
    $draftStore->find($draftReference, 18),
    '다른 사용자가 Form Draft를 읽을 수 없어야 합니다.'
);
$draftStore->discard($draftReference, 18);
jobsAssert(
    $draftStore->find($draftReference, 17) !== null,
    '다른 사용자의 폐기 요청은 Form Draft를 보존해야 합니다.'
);
$draftStore->discard($draftReference, 17);
jobsSame(
    null,
    $draftStore->find($draftReference, 17),
    '소유자가 Form Draft를 폐기할 수 있어야 합니다.'
);
$draftDirectory = $draftRoot . DIRECTORY_SEPARATOR . 'form-drafts';
$draftStore->store(
    'draft_cleanup_test',
    17,
    'problem',
    '1000',
    ['value' => 'keep']
);
$draftStore->store(
    'draft_cleanup_test',
    17,
    'problem',
    '1001',
    ['value' => 'expire']
);
$draftFiles = glob($draftDirectory . DIRECTORY_SEPARATOR . '*.draft');
sort($draftFiles, SORT_STRING);
$futureRecord = json_decode((string) file_get_contents($draftFiles[0]), true);
$expiredRecord = json_decode((string) file_get_contents($draftFiles[1]), true);
$futureRecord['expires_at'] = time() + 3600;
$expiredRecord['expires_at'] = time() - 1;
file_put_contents(
    $draftFiles[0],
    json_encode($futureRecord, JSON_UNESCAPED_SLASHES),
    LOCK_EX
);
file_put_contents(
    $draftFiles[1],
    json_encode($expiredRecord, JSON_UNESCAPED_SLASHES),
    LOCK_EX
);
jobsSame(
    1,
    $draftStore->purgeExpired(1),
    '앞선 파일이 유효해도 뒤쪽 만료 Form Draft를 정리해야 합니다.'
);
jobsAssert(
    is_file($draftFiles[0]) && ! is_file($draftFiles[1]),
    '만료 정리는 유효한 Form Draft를 보존해야 합니다.'
);
unlink($draftFiles[0]);
foreach (['.htaccess', 'index.php'] as $protectionFile) {
    $path = $draftRoot . DIRECTORY_SEPARATOR . $protectionFile;
    if (is_file($path)) {
        unlink($path);
    }
}
if (is_dir($draftDirectory)) {
    rmdir($draftDirectory);
}
if (is_dir($draftRoot)) {
    rmdir($draftRoot);
}

fwrite(
    STDOUT,
    sprintf("R-05 Jobs 단위 검사 통과: assertion %d개\n", $assertions)
);
