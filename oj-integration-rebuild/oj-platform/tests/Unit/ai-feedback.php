<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AI\Application\AiFeedbackSettingsService;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackInputFactory;
use CodingPanda\OjPlatform\Modules\AI\Application\FeedbackOutputValidator;
use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService;
use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackReusePolicy;
use CodingPanda\OjPlatform\Modules\AI\Contract\FeedbackGuideRepository;
use CodingPanda\OjPlatform\Modules\AI\Contract\LearnerLevelRefresher;
use CodingPanda\OjPlatform\Modules\AI\Contract\SubmissionFeedbackRepository;
use CodingPanda\OjPlatform\Modules\AI\Domain\FeedbackGuide;
use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\AI\Domain\SubmissionFeedback;
use CodingPanda\OjPlatform\Modules\AI\Presentation\Action\AiFeedbackActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobController;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobQuery;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobControlResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmissionProvider;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskResponse;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$aiFeedbackAssertions = 0;

function aiFeedbackAssert(bool $condition, string $message): void
{
    global $aiFeedbackAssertions;
    ++$aiFeedbackAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function aiFeedbackSame(mixed $expected, mixed $actual, string $message): void
{
    aiFeedbackAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function aiFeedbackThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        aiFeedbackAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

final class AiFeedbackTestSettings implements SettingsManager
{
    /**
     * @param array<string, mixed> $value
     */
    public function __construct(public array $value)
    {
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        unset($key, $default);

        return new SettingsSnapshot(1, $this->value);
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        unset($key, $expectedRevision);
        $this->value = is_array($value) ? $value : [];

        return new SettingsSnapshot(2, $this->value);
    }
}

final class AiFeedbackTestFeatures implements FeatureFlags
{
    public function __construct(public bool $enabled = true)
    {
    }

    public function isEnabled(string $featureKey): bool
    {
        return $featureKey === 'ai_feedback' && $this->enabled;
    }
}

final class AiFeedbackTestLevelRefresher implements LearnerLevelRefresher
{
    public function refresh(int $userId): LearnerLevelProfile
    {
        return new LearnerLevelProfile(
            $userId,
            'classified',
            'beginner',
            1,
            [
                'proven_difficulty' => 20.0,
                'breadth' => 25.0,
                'recent_challenge' => 30.0,
                'stability' => 40.0,
                'skill_score' => 28.5,
            ],
            20,
            8,
            4,
            8,
            100.0,
            100.0,
            500,
            '2026-07-29 00:00:00'
        );
    }
}

final class AiFeedbackTestSubmissions implements AiFeedbackSubmissionProvider
{
    public function __construct(
        public AiFeedbackSubmission $submission,
        public bool $accessible = true
    )
    {
    }

    public function findForActor(
        int $actorUserId,
        string $actorUserLogin,
        int $submissionId
    ): ?AiFeedbackSubmission {
        unset($actorUserLogin);

        return $this->accessible
            && $actorUserId > 0
            && $submissionId === $this->submission->submissionId()
            ? $this->submission
            : null;
    }
}

final class AiFeedbackTestProblems implements LearningProblemProvider
{
    public function __construct(public LearningProblem $problem)
    {
    }

    public function findForUser(
        int $userId,
        int $problemId
    ): ?LearningProblem {
        return $userId > 0 && $problemId === $this->problem->problemId()
            ? $this->problem
            : null;
    }

    public function findManyForUser(int $userId, array $problemIds): array
    {
        $problem = $this->findForUser(
            $userId,
            $this->problem->problemId()
        );

        return $problem !== null
            && in_array($problem->problemId(), $problemIds, true)
            ? [$problem->problemId() => $problem]
            : [];
    }
    public function catalogForUser(int $userId, int $limit = 500): array
    {
        unset($userId, $limit);

        return [$this->problem];
    }

    public function recentForUser(int $userId, int $limit = 5): array
    {
        unset($userId, $limit);

        return [$this->problem];
    }

    public function publicCatalog(int $limit = 500): array
    {
        unset($limit);

        return [$this->problem];
    }

    public function catalogRevision(): string
    {
        return $this->problem->contentHash();
    }
}

final class AiFeedbackTestRepository implements SubmissionFeedbackRepository
{
    /**
     * @var array<int, SubmissionFeedback>
     */
    public array $items = [];

    public function find(int $submissionId): ?SubmissionFeedback
    {
        return $this->items[$submissionId] ?? null;
    }

    public function save(SubmissionFeedback $feedback): void
    {
        $this->items[$feedback->submissionId()] = $feedback;
    }

    public function recent(int $limit = 100): array
    {
        return array_slice(array_values($this->items), 0, $limit);
    }
}

final class AiFeedbackTestGuides implements FeedbackGuideRepository
{
    private FeedbackGuide $guide;

    public function __construct()
    {
        $this->guide = new FeedbackGuide(
            'test-guide',
            '테스트 지침',
            'beginner',
            '테스트',
            '정답 코드를 제시하지 않습니다.',
            1,
            true,
            false,
            '2026-07-29 00:00:00'
        );
    }

    public function all(bool $includeArchived = false): array
    {
        unset($includeArchived);

        return [$this->guide];
    }

    public function find(string $key): ?FeedbackGuide
    {
        return $key === $this->guide->key() ? $this->guide : null;
    }

    public function save(
        ?string $key,
        string $name,
        string $level,
        string $purpose,
        string $instructions,
        int $actorUserId
    ): FeedbackGuide {
        unset(
            $key,
            $name,
            $level,
            $purpose,
            $instructions,
            $actorUserId
        );

        return $this->guide;
    }

    public function duplicate(
        string $key,
        int $actorUserId
    ): FeedbackGuide {
        unset($key, $actorUserId);

        return $this->guide;
    }

    public function setArchived(
        string $key,
        bool $archived,
        int $actorUserId
    ): void {
        unset($key, $archived, $actorUserId);
    }

    public function revisions(string $key): array
    {
        unset($key);

        return [];
    }
}

final class AiFeedbackTestJobs implements JobDispatcher
{
    /**
     * @var array<string, mixed>
     */
    public array $call = [];

    public function enqueue(
        string $jobType,
        string $executionMode,
        int $actorUserId,
        array $targets,
        array $inputSummary,
        array $targetSummary,
        string $accessPolicyKey,
        string $accessPolicyVersion = '1',
        string $inputVersion = '1'
    ): JobEnqueueResult {
        $this->call = compact(
            'jobType',
            'executionMode',
            'actorUserId',
            'targets',
            'inputSummary',
            'targetSummary',
            'accessPolicyKey',
            'accessPolicyVersion',
            'inputVersion'
        );

        $target = $targets[0] ?? null;
        $restartAfterJobId = is_object($target)
            ? (int) ($target->payload()['restart_after_job_id'] ?? 0)
            : 0;

        return new JobEnqueueResult(
            $restartAfterJobId > 0 ? 78 : 77,
            true
        );
    }
}

final class AiFeedbackTestRouter implements AiTaskRouter
{
    public function __construct(public bool $available = true)
    {
    }

    public function canRoute(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): bool {
        return $this->available
            && $taskType === 'submission_feedback'
            && $requiresSubmissionSource;
    }

    public function snapshot(
        string $taskType,
        bool $requiresSubmissionSource = false
    ): AiTaskAssignmentSnapshot {
        if (! $this->canRoute($taskType, $requiresSubmissionSource)) {
            throw new DomainException('AI 연결이 없습니다.');
        }

        return new AiTaskAssignmentSnapshot(
            $taskType,
            3,
            'feedback.local',
            null,
            str_repeat('a', 64)
        );
    }

    public function execute(
        AiTaskAssignmentSnapshot $assignment,
        AiTaskRequest $request
    ): AiTaskResponse {
        unset($assignment, $request);

        return new AiTaskResponse(
            [
                'result_understanding' => '결과 이해',
                'step_hints' => ['힌트'],
                'review_question' => '질문',
            ],
            'feedback.local',
            'ollama',
            'gemma',
            str_repeat('a', 64)
        );
    }
}

final class AiFeedbackTestJobQuery implements JobQuery
{
    /**
     * @param array<int, JobDetails> $jobs
     */
    public function __construct(private readonly array $jobs)
    {
    }

    public function find(int $jobId): ?JobDetails
    {
        return $this->jobs[$jobId] ?? null;
    }
}

final class AiFeedbackTestJobController implements JobController
{
    public int $calls = 0;

    public function cancel(int $actorUserId, int $jobId): JobControlResult
    {
        unset($actorUserId, $jobId);
        ++$this->calls;

        return new JobControlResult(1);
    }

    public function retryFailed(
        int $actorUserId,
        int $jobId
    ): JobControlResult {
        unset($actorUserId, $jobId);
        ++$this->calls;

        return new JobControlResult(1);
    }
}

final class AiFeedbackTestAuthorization implements Authorization
{
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($userId, $capability, $context);

        return false;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        unset($userId, $capabilities);

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return false;
    }
}

final class AiFeedbackTestGuard implements ActionAccessGuard
{
    public function __construct(private readonly UserIdentity $identity)
    {
    }

    public function verifyNonce(string $actionKey, HttpRequest $request): void
    {
        unset($actionKey, $request);
    }

    public function actor(
        string $actionKey,
        HttpRequest $request,
        string $capability
    ): UserIdentity {
        unset($actionKey, $request, $capability);

        return $this->identity;
    }

    public function authenticatedActor(
        string $actionKey,
        HttpRequest $request
    ): UserIdentity {
        unset($actionKey, $request);

        return $this->identity;
    }

    public function throttle(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): void {
        unset($scope, $identifier, $limit, $windowSeconds);
    }
}

final class AiFeedbackTestErrors implements ErrorReporter
{
    public function report(Throwable $error): void
    {
        unset($error);
    }
}

function aiFeedbackTestJob(
    int $jobId,
    int $creatorUserId,
    string $status
): JobDetails {
    return new JobDetails(
        $jobId,
        'submission_ai_feedback_generate',
        'ai_feedback',
        'ai_feedback.submission.generate',
        '1',
        $creatorUserId,
        $status,
        1,
        $status === 'completed' ? 1 : 0,
        0,
        $status === 'cancelled' ? 1 : 0,
        '2026-07-29 00:00:00',
        in_array($status, ['completed', 'cancelled'], true)
            ? '2026-07-29 00:00:01'
            : null,
        ['submission_id' => 500],
        [],
        [],
        []
    );
}
/**
 * @param array<string, mixed> $settingValue
 */
function aiFeedbackService(
    AiFeedbackSubmission $submission,
    array $settingValue,
    AiFeedbackTestJobs $jobs,
    AiFeedbackTestRouter $router,
    bool $featureEnabled = true,
    bool $accessible = true
): SubmissionFeedbackJobService {
    $problem = new LearningProblem(
        1000,
        '두 수 더하기',
        '두 수의 합을 구합니다.',
        '두 정수',
        '두 정수의 합',
        '',
        1,
        ['basic'],
        20,
        30,
        true,
        hash('sha256', 'problem-1000')
    );

    return new SubmissionFeedbackJobService(
        new AiFeedbackTestSubmissions($submission, $accessible),
        new AiFeedbackTestProblems($problem),
        new AiFeedbackTestRepository(),
        new AiFeedbackTestGuides(),
        new AiFeedbackSettingsService(
            new AiFeedbackTestSettings($settingValue)
        ),
        new FeedbackInputFactory(),
        new AiFeedbackTestLevelRefresher(),
        new AiFeedbackTestFeatures($featureEnabled),
        $jobs,
        $router
    );
}

$settings = [
    'enabled' => true,
    'result_scope' => 'all',
    'active_guides' => [
        'insufficient' => 'test-guide',
        'beginner' => 'test-guide',
        'intermediate' => 'test-guide',
        'advanced' => 'test-guide',
    ],
];
$feedbackSettings = new AiFeedbackSettingsService(
    new AiFeedbackTestSettings($settings)
);
aiFeedbackSame(
    true,
    $feedbackSettings->usesGuide('test-guide'),
    '현재 수준 정책에서 사용하는 지침을 식별해야 합니다.'
);
aiFeedbackSame(
    false,
    $feedbackSettings->usesGuide('unused-guide'),
    '사용하지 않는 지침은 보관 가능 대상으로 구분해야 합니다.'
);

$submission = new AiFeedbackSubmission(
    500,
    1000,
    9,
    6,
    6,
    10,
    1024,
    '2026-07-29 00:00:00',
    "a, b = map(int, input().split())\nprint(a - b)",
    '',
    '',
    true,
    false
);
$jobs = new AiFeedbackTestJobs();
$router = new AiFeedbackTestRouter();
$service = aiFeedbackService($submission, $settings, $jobs, $router);
aiFeedbackAssert(
    $service->canGenerate($submission),
    '완료된 유효 제출과 소스 허용 AI 연결은 피드백을 생성할 수 있어야 합니다.'
);
$jobController = new AiFeedbackTestJobController();
$action = new AiFeedbackActionHandler(
    $service,
    new AiFeedbackTestJobQuery([
        77 => aiFeedbackTestJob(77, 9, 'cancelled'),
        78 => aiFeedbackTestJob(78, 9, 'queued'),
        79 => aiFeedbackTestJob(79, 8, 'queued'),
    ]),
    $jobController,
    new AiFeedbackTestAuthorization(),
    new AiFeedbackTestGuard(new UserIdentity(
        9,
        'learner',
        'learner@example.com',
        '학습자'
    )),
    new AiFeedbackTestErrors()
);
$generateResponse = $action->handle(new HttpRequest(
    'POST',
    '/ajax',
    [],
    ['submission_id' => 500, 'operation' => 'generate']
));
$generatePayload = json_decode(
    $generateResponse->body(),
    true,
    512,
    JSON_THROW_ON_ERROR
);
aiFeedbackSame(
    78,
    $generatePayload['job_id'] ?? null,
    '취소된 동일 입력 작업이 있어도 새 작업 세대로 다시 등록해야 합니다.'
);
$sharedStatusResponse = $action->handle(new HttpRequest(
    'POST',
    '/ajax',
    [],
    [
        'submission_id' => 500,
        'operation' => 'status',
        'job_id' => 79,
    ]
));
$sharedStatusPayload = json_decode(
    $sharedStatusResponse->body(),
    true,
    512,
    JSON_THROW_ON_ERROR
);
aiFeedbackSame(
    200,
    $sharedStatusResponse->status(),
    '현재 제출 상세 권한이 있는 사용자는 공유된 멱등 작업 상태를 조회할 수 있어야 합니다.'
);
aiFeedbackSame(
    false,
    $sharedStatusPayload['can_cancel'] ?? null,
    '다른 사용자가 만든 공유 작업은 조회만 허용하고 제어 권한은 주면 안 됩니다.'
);
$sharedCancelResponse = $action->handle(new HttpRequest(
    'POST',
    '/ajax',
    [],
    [
        'submission_id' => 500,
        'operation' => 'cancel',
        'job_id' => 79,
    ]
));
aiFeedbackSame(
    404,
    $sharedCancelResponse->status(),
    '공유 작업의 취소·재시도는 생성자 또는 AI 관리자만 할 수 있어야 합니다.'
);
aiFeedbackSame(
    0,
    $jobController->calls,
    '권한 없는 공유 작업 제어 요청은 Jobs 제어기로 전달하면 안 됩니다.'
);$result = $service->enqueue(9, 'learner', 500);
aiFeedbackSame(77, $result->jobId(), 'AI 피드백을 Jobs에 등록해야 합니다.');
aiFeedbackSame(
    'submission_ai_feedback_generate',
    $jobs->call['jobType'],
    'AI 피드백은 전용 Job 유형을 사용해야 합니다.'
);
aiFeedbackSame(
    'ai_feedback.submission.generate',
    $jobs->call['accessPolicyKey'],
    'AI 피드백 Job은 전용 접근 정책을 사용해야 합니다.'
);
$target = $jobs->call['targets'][0] ?? null;
aiFeedbackAssert(
    $target !== null
        && $target->targetType() === 'submission'
        && $target->targetKey() === '500',
    'Jobs 대상을 제출 한 건으로 고정해야 합니다.'
);
$payload = $target->payload();
aiFeedbackAssert(
    isset(
        $payload['assignment'],
        $payload['base_input_hash'],
        $payload['source_hash'],
        $payload['problem_hash'],
        $payload['generation_context_hash']
    ),
    '작업 배정, 수준·지침 문맥과 변경 감지 해시를 등록 시점에 고정해야 합니다.'
);
aiFeedbackSame(
    $payload['generation_context_hash'],
    $jobs->call['inputSummary']['generation_context_hash'] ?? null,
    '학습 수준과 답변 지침이 바뀌면 Jobs 멱등 키도 달라져야 합니다.'
);
$service->enqueue(9, 'learner', 500, 77);
aiFeedbackSame(
    77,
    $jobs->call['targets'][0]->payload()['restart_after_job_id'] ?? null,
    '취소 작업 뒤 재요청은 새 작업 세대 번호를 대상 입력에 포함해야 합니다.'
);
aiFeedbackSame(
    77,
    $jobs->call['inputSummary']['restart_after_job_id'] ?? null,
    '취소 작업 뒤 재요청은 Jobs 멱등 입력을 바꿔야 합니다.'
);
$encodedPayload = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
aiFeedbackAssert(
    is_string($encodedPayload)
        && ! str_contains($encodedPayload, 'learner')
        && ! str_contains($encodedPayload, '@'),
    'AI 입력에는 로그인 아이디나 이메일을 포함하면 안 됩니다.'
);
aiFeedbackAssert(
    str_contains($encodedPayload, 'print(a - b)'),
    '명시적 요청 Job에는 현재 제출 소스가 포함되어야 합니다.'
);
$reuseProblem = new LearningProblem(
    1000,
    '두 수 더하기',
    '두 수의 합을 구합니다.',
    '두 정수',
    '두 정수의 합',
    '',
    1,
    ['basic'],
    20,
    30,
    true,
    hash('sha256', 'problem-1000')
);
$reuseProfile = (new AiFeedbackTestLevelRefresher())->refresh(9);
$reuseAssignment = $router->snapshot('submission_feedback', true);
$reuseInputHash = str_repeat('b', 64);
$storedFeedback = new SubmissionFeedback(
    500,
    [
        'result_understanding' => '결과 이해',
        'step_hints' => ['힌트'],
        'review_question' => '질문',
    ],
    $reuseInputHash,
    $submission->sourceHash(),
    $reuseProblem->contentHash(),
    $submission->resultCode(),
    'test-guide',
    1,
    $reuseProfile->analysisStatus(),
    $reuseProfile->level(),
    $reuseProfile->policyVersion(),
    $reuseProfile->lastSubmissionId(),
    $reuseAssignment->routingRevision(),
    $reuseAssignment->primaryProfileKey(),
    'ollama',
    $reuseAssignment->profileFingerprint(),
    'gemma',
    [],
    '2026-07-29 00:00:00',
    '2026-07-29 00:00:00'
);
$reusePolicy = new SubmissionFeedbackReusePolicy();
aiFeedbackAssert(
    $reusePolicy->matches(
        $storedFeedback,
        $submission,
        $reuseProblem,
        $reuseProfile,
        'test-guide',
        1,
        $reuseAssignment,
        $reuseInputHash
    ),
    '제출·문제·수준·지침·AI 배정이 같으면 성공 결과를 재사용해야 합니다.'
);
aiFeedbackAssert(
    ! $reusePolicy->matches(
        $storedFeedback,
        $submission,
        $reuseProblem,
        $reuseProfile,
        'test-guide',
        2,
        $reuseAssignment,
        $reuseInputHash
    ),
    '답변 지침 개정이 바뀌면 기존 성공 결과를 재사용하면 안 됩니다.'
);

aiFeedbackAssert(
    $service->isCurrent($storedFeedback, $submission, $reuseProblem),
    '제출 코드·문제 원문·채점 결과가 같을 때만 저장 피드백을 현재 결과로 봐야 합니다.'
);
$changedSubmission = new AiFeedbackSubmission(
    500,
    1000,
    9,
    6,
    6,
    10,
    1024,
    '2026-07-29 00:00:00',
    'print(0)',
    '',
    '',
    true,
    false
);
aiFeedbackAssert(
    ! $service->isCurrent(
        $storedFeedback,
        $changedSubmission,
        $reuseProblem
    ),
    '제출 코드가 바뀐 이전 피드백은 화면과 AJAX 응답에서 숨겨야 합니다.'
);

$outputValidator = new FeedbackOutputValidator();
$validatedOutput = $outputValidator->validate([
    'result_understanding' => '출력값이 예상과 다릅니다.',
    'step_hints' => ['반복문의 종료 조건을 한 단계씩 확인해 보세요.'],
    'review_question' => '마지막 반복에서 인덱스 값은 얼마인가요?',
]);
aiFeedbackSame(
    '출력값이 예상과 다릅니다.',
    $validatedOutput['result_understanding'],
    '복사 가능한 코드가 없는 학습 안내는 허용해야 합니다.'
);
aiFeedbackThrows(
    static fn () => $outputValidator->validate([
        'result_understanding' => '아래 코드를 사용하세요.',
        'step_hints' => ['print(sum(map(int, input().split())))'],
        'review_question' => '이해했나요?',
    ]),
    '코드 울타리가 없어도 바로 복사할 수 있는 정답 코드는 거부해야 합니다.'
);
aiFeedbackThrows(
    static fn () => $outputValidator->validate([
        'result_understanding' => '한 줄을 추가하세요.',
        'step_hints' => ['System.out.println(answer);'],
        'review_question' => '이해했나요?',
    ]),
    'Java 한 줄 정답 코드도 코드 울타리와 관계없이 거부해야 합니다.'
);
aiFeedbackThrows(
    static fn () => $outputValidator->validate([
        'result_understanding' => '변수를 선언하세요.',
        'step_hints' => ['int answer = left + right;'],
        'review_question' => '이해했나요?',
    ]),
    '복사 가능한 단독 변수 선언도 거부해야 합니다.'
);aiFeedbackThrows(
    static fn () => $outputValidator->validate([
        'result_understanding' => '함수를 추가하세요.',
        'step_hints' => ["def solve():\n    return 0"],
        'review_question' => '이해했나요?',
    ]),
    '함수 전체 형태의 코드는 거부해야 합니다.'
);
aiFeedbackThrows(
    static fn () => $outputValidator->validate([
        'result_understanding' => '다음 패치를 적용하세요.',
        'step_hints' => ["--- a/main.py\n+++ b/main.py\n+print(1)"],
        'review_question' => '이해했나요?',
    ]),
    '복사 가능한 수정 패치도 거부해야 합니다.'
);
$restrictedService = aiFeedbackService(
    $submission,
    $settings,
    new AiFeedbackTestJobs(),
    new AiFeedbackTestRouter(),
    true,
    false
);
aiFeedbackThrows(
    static fn () => $restrictedService->assertCanAccess(9, 'learner', 500),
    '수행 모드나 상세 권한이 바뀌면 AJAX 상태·결과 조회도 다시 차단해야 합니다.'
);

$moduleDisabledService = aiFeedbackService(
    $submission,
    $settings,
    new AiFeedbackTestJobs(),
    new AiFeedbackTestRouter(),
    false
);
aiFeedbackAssert(
    ! $moduleDisabledService->canGenerate($submission),
    'AI 피드백 모듈 기능을 끄면 직접 AJAX 요청도 Jobs를 등록할 수 없어야 합니다.'
);

$disabled = $settings;
$disabled['enabled'] = false;
$disabledService = aiFeedbackService(
    $submission,
    $disabled,
    new AiFeedbackTestJobs(),
    new AiFeedbackTestRouter()
);
aiFeedbackAssert(
    ! $disabledService->canGenerate($submission),
    '기능을 끄면 AI 피드백을 요청할 수 없어야 합니다.'
);
aiFeedbackThrows(
    static fn () => $disabledService->enqueue(9, 'learner', 500),
    '꺼진 기능은 Jobs 등록도 거부해야 합니다.'
);

$incorrectOnly = $settings;
$incorrectOnly['result_scope'] = 'incorrect';
$acceptedSubmission = new AiFeedbackSubmission(
    501,
    1000,
    9,
    6,
    4,
    10,
    1024,
    '2026-07-29 00:01:00',
    'print(3)',
    '',
    '',
    true,
    false
);
$incorrectService = aiFeedbackService(
    $acceptedSubmission,
    $incorrectOnly,
    new AiFeedbackTestJobs(),
    new AiFeedbackTestRouter()
);
aiFeedbackAssert(
    ! $incorrectService->canGenerate($acceptedSubmission),
    '오답 전용 정책에서는 정답 제출을 분석하면 안 됩니다.'
);

$unavailableService = aiFeedbackService(
    $submission,
    $settings,
    new AiFeedbackTestJobs(),
    new AiFeedbackTestRouter(false)
);
aiFeedbackAssert(
    ! $unavailableService->canGenerate($submission),
    '제출 소스를 허용한 AI 경로가 없으면 생성 버튼을 제공하면 안 됩니다.'
);

echo sprintf(
    "R-08 AI 피드백 Job 단위 검사 통과: assertion %d개\n",
    $aiFeedbackAssertions
);
